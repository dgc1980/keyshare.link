-- phpMyAdmin SQL Dump
-- version 4.9.7
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Feb 01, 2022 at 08:24 PM
-- Server version: 10.4.22-MariaDB
-- PHP Version: 7.3.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `keyshare_keyshare`
--

-- --------------------------------------------------------

--
-- Table structure for table `accountprefs`
--

CREATE TABLE `accountprefs` (
  `id` int(11) NOT NULL,
  `username` text NOT NULL,
  `optout` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;



-- --------------------------------------------------------

--
-- Table structure for table `gamekeys`
--

CREATE TABLE `gamekeys` (
  `id` int(11) NOT NULL,
  `hash` varchar(32) NOT NULL,
  `gametitle` text NOT NULL,
  `gamekey` text NOT NULL,
  `dateadded` int(11) NOT NULL,
  `dateclaimed` int(11) NOT NULL DEFAULT 0,
  `startdate` int(11) NOT NULL,
  `captcha` int(11) NOT NULL DEFAULT 1,
  `reddit` int(11) NOT NULL DEFAULT 1,
  `claimed` int(11) NOT NULL DEFAULT 0,
  `reddit_who` text NOT NULL DEFAULT '',
  `reddit_owner` text NOT NULL,
  `worked` int(11) NOT NULL DEFAULT 0,
  `reported` int(11) NOT NULL DEFAULT 0,
  `reportreason` text NOT NULL DEFAULT '',
  `checked` int(11) NOT NULL DEFAULT 0,
  `karma_link` int(11) NOT NULL,
  `karma_comment` int(11) NOT NULL,
  `account_age` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;



-- --------------------------------------------------------

--
-- Table structure for table `ratelimit`
--

CREATE TABLE `ratelimit` (
  `id` int(11) NOT NULL,
  `who` text NOT NULL,
  `lastclaim` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;



--
-- Indexes for dumped tables
--

--
-- Indexes for table `accountprefs`
--
ALTER TABLE `accountprefs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `gamekeys`
--
ALTER TABLE `gamekeys`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `ratelimit`
--
ALTER TABLE `ratelimit`
  ADD PRIMARY KEY (`id`);



/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
